import { ApiProperty } from '@nestjs/swagger';
import { IsString, MinLength } from 'class-validator';

export class AssignLicenseDto {
  @ApiProperty({ example: 'ABCD-EFGH-IJKL' })
  @IsString()
  @MinLength(1)
  code!: string;
}

