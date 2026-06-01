import { ApiProperty } from '@nestjs/swagger';

export class AccessoryDto {
  @ApiProperty({ example: 1 })
  id!: number;

  @ApiProperty({ example: 'Cargador' })
  name!: string;
}

