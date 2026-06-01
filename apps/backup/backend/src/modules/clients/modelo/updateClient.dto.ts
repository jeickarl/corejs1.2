import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsEmail, IsIn, IsOptional, IsString, MinLength } from 'class-validator';

export class UpdateClientDto {
  @ApiProperty({ enum: ['individual', 'company'] })
  @IsIn(['individual', 'company'])
  clientType!: 'individual' | 'company';

  @ApiPropertyOptional({ example: 'Juan Perez' })
  @IsOptional()
  @IsString()
  @MinLength(1)
  name?: string;

  @ApiPropertyOptional({ example: 'Empresa SAS' })
  @IsOptional()
  @IsString()
  @MinLength(1)
  companyName?: string;

  @ApiPropertyOptional({ example: '900.123.456-7' })
  @IsOptional()
  @IsString()
  @MinLength(1)
  taxId?: string;

  @ApiPropertyOptional({ example: 'Representante Legal' })
  @IsOptional()
  @IsString()
  legalRepresentative?: string;

  @ApiProperty({ example: '+57 3001234567' })
  @IsString()
  @MinLength(1)
  phone!: string;

  @ApiPropertyOptional({ example: 'cliente@correo.com' })
  @IsOptional()
  @IsEmail()
  email?: string;

  @ApiPropertyOptional({ example: '1234567890' })
  @IsOptional()
  @IsString()
  idNumber?: string;

  @ApiPropertyOptional({ example: 'Calle 1 #2-3' })
  @IsOptional()
  @IsString()
  address?: string;

  @ApiPropertyOptional({ example: 'Notas internas' })
  @IsOptional()
  @IsString()
  notes?: string;
}

