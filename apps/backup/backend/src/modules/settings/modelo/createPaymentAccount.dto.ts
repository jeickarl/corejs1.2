import { ApiProperty, ApiPropertyOptional } from '@nestjs/swagger';
import { IsBoolean, IsOptional, IsString, MinLength } from 'class-validator';

export class CreatePaymentAccountDto {
  @ApiProperty({ example: 'Cuenta Bancolombia' })
  @IsString()
  @MinLength(1)
  alias!: string;

  @ApiPropertyOptional({ example: '123-456-789' })
  @IsOptional()
  @IsString()
  accountNumber?: string;

  @ApiPropertyOptional({ example: 'Ahorros' })
  @IsOptional()
  @IsString()
  accountType?: string;

  @ApiPropertyOptional({ example: 'Juan Pérez' })
  @IsOptional()
  @IsString()
  holderName?: string;

  @ApiPropertyOptional({ example: 'CC 123' })
  @IsOptional()
  @IsString()
  holderId?: string;

  @ApiPropertyOptional({ example: true })
  @IsOptional()
  @IsBoolean()
  isActive?: boolean;
}

